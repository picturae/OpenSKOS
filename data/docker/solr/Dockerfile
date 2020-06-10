FROM solr:7.5
ENV SOLR_HOME /opt/solr/server/solr
USER root
RUN groupadd --gid 1000 data
#RUN groupadd --gid   20 staff
RUN usermod -a -G data solr
RUN usermod -a -G staff solr
USER solr
