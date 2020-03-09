FROM solr:7.5
ENV SOLR_HOME /opt/solr/server/solr
USER root
RUN groupadd --gid 1000 data
RUN usermod -a -G data solr
USER solr
